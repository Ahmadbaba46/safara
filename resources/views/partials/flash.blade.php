@if (session('status'))
    <div class="flash flash-ok" role="status">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="flash flash-err" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="flash flash-err" role="alert">{{ $errors->first() }}</div>
@endif
