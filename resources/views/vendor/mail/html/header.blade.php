@props(['url'])
@php
    use App\Support\Brand;
@endphp
<tr>
<td class="header">
{{--
    The lockup, in the shape the app uses everywhere else — a mark beside two
    lines of text — but built from an <img> and a table rather than inline SVG.

    Gmail STRIPS <svg> entirely, so `x-brand.mark` cannot be reused and the mark
    has to be a raster. `icon-192` is the smallest shipped PNG that still holds
    up scaled down, and it comes from Brand, so an upload on the App Branding
    page reaches the email too.

    The wordmark is real text, not part of the image, so it survives a client
    blocking images — which is the state a first email from an unknown sender is
    most likely to be read in. It will NOT render in Archivo: email clients do
    not load webfonts reliably, so the stack degrades to the system grotesque
    and the tracking does the recognising.

    Colors are INLINE rather than in the theme stylesheet because that file is
    read with file_get_contents, not rendered as Blade — it cannot call Brand,
    so anything the admin can retint has to be set here.
--}}
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="padding-right: 10px; vertical-align: middle;">
<img src="{{ Brand::asset('icon-192') }}" width="36" height="36" alt="" style="display: block; width: 36px; height: 36px; border: 0;">
</td>
<td style="vertical-align: middle; text-align: left;">
<span style="display: block; font-size: 10px; font-weight: 600; letter-spacing: 3px; text-transform: uppercase; color: #71717a; line-height: 1.1;">{{ Brand::wordmark()['lead'] }}</span>
<span style="display: block; font-size: 21px; font-weight: 800; letter-spacing: -0.5px; color: {{ Brand::color('ink') }}; line-height: 1.1;">{{ Brand::wordmark()['main'] }}</span>
</td>
</tr>
</table>
</a>
</td>
</tr>
