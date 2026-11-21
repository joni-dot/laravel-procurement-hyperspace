{{-- The list is an HTML block so Markdown never formats text typed by users, such as package titles. --}}
<x-mail::message>
{{ $intro }}

<table width="100%" cellpadding="6" style="border-collapse: collapse; margin-bottom: 24px;">
@foreach ($items as $item)
<tr style="border-top: 1px solid #e8e5ef;"><td><a href="{{ $item['url'] }}">{{ $item['message'] }}</a><br><span style="color: #6b7280; font-size: 13px;">{{ $item['source'] }}</span></td></tr>
@endforeach
@if ($more > 0)
<tr style="border-top: 1px solid #e8e5ef;"><td>{{ __('And :count more.', ['count' => $more]) }}</td></tr>
@endif
</table>

<x-mail::button :url="$url">
{{ __('Open notifications') }}
</x-mail::button>

{{ __('You can turn these emails off under Settings → Notifications.') }}
</x-mail::message>
