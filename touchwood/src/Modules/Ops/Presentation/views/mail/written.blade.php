@extends('ops::mail.layout')

@section('body')
    {{-- What staff wrote (ops.md §1.7, §1.8), or the newsletter's confirmation (§1.9), in the shared layout.
         Staff's own text keeps its line breaks and nothing else: every line is escaped. --}}
    @foreach ($lines as $line)
        <p style="margin: 0 0 12px;">{!! nl2br(e($line)) !!}</p>
    @endforeach

    @if ($link)
        @include('ops::mail.button', ['href' => $link, 'label' => $action])
    @endif

    @if ($unsubscribe)
        <p style="margin: 16px 0 0; color: #53626b; font-size: 12px;">{{ $leave['text'] }} <a href="{{ $unsubscribe }}" style="color: #53626b;">{{ $leave['action'] }}</a></p>
    @endif
@endsection
