@props(['label', 'value', 'delta' => null, 'note' => null, 'hero' => false])
<div {{ $attributes->class(['tile', 'tile--hero' => $hero]) }}>
    <div class="tile__label">{{ $label }}</div>
    <div class="tile__value">{{ $value }}</div>
    @if ($delta)
        <div @class(['delta', 'delta--good' => $delta['good'] === true, 'delta--bad' => $delta['good'] === false])>
            <span aria-hidden="true">{{ ['up' => '▲', 'down' => '▼', 'flat' => '■'][$delta['dir']] }}</span>
            {{ $delta['text'] }} <span class="delta__vs">vs previous</span>
        </div>
    @endif
    @if ($note)
        <div class="tile__note">{{ $note }}</div>
    @endif
</div>
