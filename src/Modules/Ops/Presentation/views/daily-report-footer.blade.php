<table width="100%" style="border-top: 0.6pt solid #dcdfe1; font-size: 7.5pt; color: #87949a;">
    <tr>
        <td style="padding-top: 4pt;">TouchWood · {{ __('ops::daily_report.title', [], $locale) }}</td>
        <td style="padding-top: 4pt; text-align: {{ $locale === 'ar' ? 'left' : 'right' }};">{{ __('ops::daily_report.page', [], $locale) }} <bdi dir="ltr">{PAGENO}</bdi> {{ __('ops::daily_report.of', [], $locale) }} <bdi dir="ltr">{nbpg}</bdi></td>
    </tr>
</table>
