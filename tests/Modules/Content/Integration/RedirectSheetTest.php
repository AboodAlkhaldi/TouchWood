<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\ApplyRedirectSheet\ApplyRedirectSheet;
use Modules\Content\Application\Command\ApplyRedirectSheet\ApplyRedirectSheetHandler;
use Modules\Content\Application\Command\UploadRedirectSheet\UploadRedirectSheet;
use Modules\Content\Application\Command\UploadRedirectSheet\UploadRedirectSheetHandler;
use Modules\Content\Application\Query\Redirects\RedirectsHandler;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\SheetRefused;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\seed;

/*
| The redirect sheet (content.md §1.15, W11b, §8 #16): xlsx or csv, two columns under the headings Old Address and New
| Address, 5,000 lines and 2 MB at most. Upload checks it line by line - New, Same as Now, Change, Refused and why -
| and writes nothing; only Apply Sheet writes it, and it never deletes a line. Lesson 87: Apply's own transaction is
| proved by a browser test with the screens (plan PR 7); RefreshDatabase wraps these.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
    Rx::superAdmin();
});

afterEach(function () {
    foreach (glob(testTempDirectory('redirect-sheets').'/*') ?: [] as $file) {
        unlink($file);
    }
});

/**
 * A sheet file of these rows.
 *
 * @param  list<list<string>>  $rows
 */
function redirectSheetFile(array $rows, string $type = 'csv'): string
{
    $folder = testTempDirectory('redirect-sheets');
    is_dir($folder) || mkdir($folder, 0777, true);
    $path = $folder.'/'.uniqid('sheet', true).'.'.$type;

    if ($type === 'xlsx') {
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    $handle = fopen($path, 'w') ?: throw new LogicException('No file.');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    fclose($handle);

    return $path;
}

/**
 * @param  list<list<string>>  $rows
 */
function redirectSheetUpload(array $rows, string $type = 'csv', ?string $name = null): string
{
    $path = redirectSheetFile($rows, $type);

    return app(UploadRedirectSheetHandler::class)->handle(new UploadRedirectSheet($path, $name ?? "redirects.{$type}", (int) filesize($path)));
}

function redirectSheetRefusal(callable $work): string
{
    try {
        $work();
    } catch (SheetRefused $refused) {
        return $refused->rule;
    }

    return 'none';
}

describe('uploading', function () {
    it('checks each line against the redirects as they are, writing nothing', function (string $type) {
        Rx::line('/same', '/sa/ar/x');
        Rx::line('/changed', '/sa/ar/x');

        $id = redirectSheetUpload([
            ['Old Address', 'New Address'],
            ['/new', '/sa/ar/categories/kitchens'],
            ['/same/', '/sa/ar/x'],
            ['/changed', '/sa/en/y'],
            ['/admin/x', '/sa/ar'],
            ['/sa', '/sa/ar'],
            ['/new', '/sa/en'],
            ['', ''],
            ['/no-store', '/zz/ar'],
        ], $type);

        $sheet = app(RedirectsHandler::class)->sheet($id);

        expect(array_map(static fn (array $line): array => [$line['line'], $line['old'], $line['result'], $line['reason']], $sheet['lines']))->toBe([
            [2, '/new', 'NEW', null],
            [3, '/same', 'SAME', null],
            [4, '/changed', 'CHANGE', null],
            [5, '/admin/x', 'REFUSED', 'old_reserved'],
            [6, '/sa', 'REFUSED', 'old_store'],
            [7, '/new', 'REFUSED', 'twice'],
            [9, '/no-store', 'REFUSED', 'new_store'],
        ])
            ->and($sheet['counts'])->toBe(['NEW' => 1, 'SAME' => 1, 'CHANGE' => 1, 'REFUSED' => 4])
            ->and($sheet['applied'])->toBeFalse()
            ->and(DB::table('content.redirects')->count())->toBe(2)
            ->and(DB::table('content.redirects')->where('old_path', '/changed')->value('new_path'))->toBe('/sa/ar/x');
    })->with(['csv', 'xlsx']);

    it('reads the headings whatever their case and spacing, and refuses a sheet without them', function () {
        expect(redirectSheetUpload([['  old   ADDRESS ', 'new address'], ['/a', '/sa/ar']]))->not->toBe('')
            ->and(redirectSheetRefusal(fn () => redirectSheetUpload([['/a', '/sa/ar'], ['/b', '/sa/ar']])))->toBe(SheetRefused::HEADINGS)
            ->and(redirectSheetRefusal(fn () => redirectSheetUpload([['New Address', 'Old Address'], ['/b', '/sa/ar']])))->toBe(SheetRefused::HEADINGS);
    });

    it('refuses a sheet whole: not xlsx or csv, over 2 MB, over 5,000 lines, no line', function () {
        $path = redirectSheetFile([['Old Address', 'New Address'], ['/a', '/sa/ar']]);
        $upload = static fn (string $name, int $bytes): string => app(UploadRedirectSheetHandler::class)->handle(new UploadRedirectSheet($path, $name, $bytes));

        expect(redirectSheetRefusal(fn () => $upload('redirects.xls', 100)))->toBe(SheetRefused::TYPE)
            ->and(redirectSheetRefusal(fn () => $upload('redirects', 100)))->toBe(SheetRefused::TYPE)
            ->and(redirectSheetRefusal(fn () => $upload('redirects.csv', 2 * 1024 * 1024 + 1)))->toBe(SheetRefused::TOO_LARGE)
            ->and(redirectSheetRefusal(fn () => $upload('redirects.csv', 2 * 1024 * 1024)))->toBe('none')
            ->and(redirectSheetRefusal(fn () => redirectSheetUpload([['Old Address', 'New Address']])))->toBe(SheetRefused::EMPTY)
            ->and(redirectSheetRefusal(fn () => redirectSheetUpload([['Old Address', 'New Address'], ...array_fill(0, 5001, ['/a', '/sa/ar'])])))->toBe(SheetRefused::TOO_MANY_LINES)
            ->and(redirectSheetRefusal(fn () => redirectSheetUpload([['Old Address', 'New Address'], ...array_fill(0, 5000, ['/a', '/sa/ar'])])))->toBe('none');
    });

    it('refuses a file of the right name that is not one', function () {
        $path = testTempDirectory('redirect-sheets').'/broken.xlsx';
        is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'not a workbook');

        expect(redirectSheetRefusal(fn () => app(UploadRedirectSheetHandler::class)->handle(new UploadRedirectSheet($path, 'broken.xlsx', 14))))->toBe(SheetRefused::TYPE);
    });
});

describe('applying', function () {
    it('writes the New and Change lines, never deletes one, and audits each', function () {
        Rx::line('/kept', '/sa/ar/x');
        Rx::line('/changed', '/sa/ar/x');
        $id = redirectSheetUpload([['Old Address', 'New Address'], ['/new', '/sa/ar/y'], ['/changed', '/sa/en/z'], ['/admin', '/sa/ar']]);

        $counts = app(ApplyRedirectSheetHandler::class)->handle(new ApplyRedirectSheet($id));

        expect($counts)->toBe(['added' => 1, 'changed' => 1])
            ->and(DB::table('content.redirects')->orderBy('old_path')->pluck('new_path', 'old_path')->all())->toBe(['/changed' => '/sa/en/z', '/kept' => '/sa/ar/x', '/new' => '/sa/ar/y'])
            ->and(DB::table('content.redirects')->where('old_path', '/new')->value('source'))->toBe('SHEET')
            ->and(Fx::audits('content.redirect.added'))->toBe(3)
            ->and(Fx::audits('content.redirect.changed'))->toBe(1)
            ->and(app(RedirectsHandler::class)->sheet($id)['applied'])->toBeTrue();
    });

    it('checks the lines again as they are at that moment', function () {
        $id = redirectSheetUpload([['Old Address', 'New Address'], ['/taken-since', '/sa/ar/y'], ['/fine', '/sa/ar/z']]);
        // Meanwhile, a line typed by hand takes one old address, going to the same page.
        Rx::line('/taken-since', '/sa/ar/y');

        expect(app(ApplyRedirectSheetHandler::class)->handle(new ApplyRedirectSheet($id)))->toBe(['added' => 1, 'changed' => 0]);
    });

    it('applies a sheet once', function () {
        $id = redirectSheetUpload([['Old Address', 'New Address'], ['/new', '/sa/ar/y']]);
        app(ApplyRedirectSheetHandler::class)->handle(new ApplyRedirectSheet($id));

        expect(fn () => app(ApplyRedirectSheetHandler::class)->handle(new ApplyRedirectSheet($id)))->toThrow(InvalidContentInput::class);
    });

    it('drops a sheet after 24 hours', function () {
        $id = redirectSheetUpload([['Old Address', 'New Address'], ['/new', '/sa/ar/y']]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(24)->addSecond());

        expect(fn () => app(ApplyRedirectSheetHandler::class)->handle(new ApplyRedirectSheet($id)))->toThrow(ContentNotFound::class)
            ->and(fn () => app(RedirectsHandler::class)->sheet($id))->toThrow(ContentNotFound::class);

        // The next upload removes it.
        redirectSheetUpload([['Old Address', 'New Address'], ['/b', '/sa/ar']]);
        expect(DB::table('content.redirect_uploads')->where('id', $id)->exists())->toBeFalse();
    });
});
