@php($gtmBase = rtrim(config('services.gtm.server_url'), '/'))
<noscript><iframe src="{{ $gtmBase }}/ns.html?id={{ config('services.gtm.id') }}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
