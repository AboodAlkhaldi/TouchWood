/*
| An address as the store's printed form lays it out: one line per line of the template (frontend.md
| §3.6, access.md §1.9).
|
| Each line is isolated (bdi) and finds its own direction. A line such as "7 King Fahd Road" inside
| an Arabic page would otherwise be read right to left and shown as "King Fahd Road 7"; isolated, it
| reads as it was written, while the block keeps the page's alignment (batch C screenshots,
| 2026-10-04). Empty lines are dropped, as the printed form drops them.
*/

export function AddressLines({ text }: { text: string }) {
    return (
        <>
            {text
                .split('\n')
                .filter((line) => line.trim() !== '')
                .map((line, index) => (
                    <span key={index} className="block">
                        <bdi>{line}</bdi>
                    </span>
                ))}
        </>
    );
}
