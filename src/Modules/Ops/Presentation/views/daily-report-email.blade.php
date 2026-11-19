@extends('ops::mail.layout')

@section('body')
    {{-- With the store's logo and name (ops.md §1.1); every store's report, TouchWood's own. --}}
    @foreach ($lines as $line)
        <p style="margin: 0 0 12px;">{{ $line }}</p>
    @endforeach
@endsection
