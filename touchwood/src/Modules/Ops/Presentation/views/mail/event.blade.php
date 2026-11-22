@extends('ops::mail.layout')

@section('body')
    {{-- A message about a moment (ops.md §1.5, §1.6), in the shared layout. Every value is escaped: staff's
         words (a reason), names and an address come from other modules; a line of several (the address)
         keeps its line breaks. --}}
    @foreach ($lines as $line)
        <p style="margin: 0 0 12px;">{!! nl2br(e($line)) !!}</p>
    @endforeach

    @if ($summary !== [])
        <table role="presentation" width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse: separate; border: 1px solid #dcdfe1; border-radius: 8px; margin: 8px 0 16px;">
            @foreach ($summary as $row)
                @php($last = $loop->last)
                <tr>
                    <td style="{{ $last ? 'font-weight: bold;' : 'border-bottom: 1px solid #dcdfe1;' }}">{{ $row['label'] }}</td>
                    <td style="{{ $last ? 'font-weight: bold;' : 'border-bottom: 1px solid #dcdfe1;' }} white-space: nowrap;" dir="ltr">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($link)
        @include('ops::mail.button', ['href' => $link, 'label' => $action])
    @endif

    @if ($trackLink)
        @include('ops::mail.button', ['href' => $trackLink, 'label' => $trackAction, 'outlined' => true])
    @endif
@endsection
