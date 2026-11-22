@extends('ops::mail.layout')

@section('body')
    {{-- A security message (ops.md §1.5 C18), in the shared layout. The address written under the button
         is the raw-URL line this email always had. --}}
    @foreach ($lines as $line)
        <p style="margin: 0 0 12px;">{{ $line }}</p>
    @endforeach

    @if ($link)
        @include('ops::mail.button', ['href' => $link, 'label' => $action])
    @endif
@endsection
