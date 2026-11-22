{{-- A bulletproof button and the link's address under it (F8). $href, $label; $outlined for the second kind. --}}
@php($fill = ($outlined ?? false) ? '#ffffff' : '#02365e')
@php($ink = ($outlined ?? false) ? '#02365e' : '#ffffff')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: separate; margin: 12px 0 4px;">
    <tr>
        <td align="center" bgcolor="{{ $fill }}" style="border: 2px solid #02365e; border-radius: 6px; background-color: {{ $fill }};">
            <a href="{{ $href }}" style="display: inline-block; padding: 10px 22px; font-family: Arial, sans-serif; font-size: 15px; font-weight: bold; color: {{ $ink }}; text-decoration: none;">{{ $label }}</a>
        </td>
    </tr>
</table>
<p style="margin: 0 0 16px; color: #53626b; font-size: 12px; word-break: break-all;" dir="ltr">{{ $href }}</p>
