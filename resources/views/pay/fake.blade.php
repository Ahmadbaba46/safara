@extends('layouts.phone')
@section('title', 'Test payment')
@section('content')
<div class="flash flash-warn">Test payments — no money moves. Choose a payment provider before going live.</div>
<h1 class="p-title">Simulate a {{ ['card' => 'card', 'transfer' => 'bank transfer', 'ussd' => 'USSD'][$method] ?? 'card' }} payment</h1>
<div class="p-summary">
    <div class="p-line"><span>Booking</span><span class="mono">{{ $payment->booking->reference }}</span></div>
    <div class="p-line"><span>Reference</span><span class="mono">{{ $payment->reference }}</span></div>
    <div class="p-total"><span class="grow strong">Amount</span><b>{{ $payment->amountLabel() }}</b></div>
</div>
<form method="post" action="{{ route('pay.fake.complete', $payment->token) }}" class="stack-s" style="margin-top:auto;gap:10px">
    @csrf
    <input type="hidden" name="method" value="{{ $method }}">
    <button class="btn btn-primary p-cta" type="submit" name="outcome" value="success">Simulate successful payment</button>
    <button class="btn p-cta" type="submit" name="outcome" value="cancel">Cancel</button>
</form>
@endsection
