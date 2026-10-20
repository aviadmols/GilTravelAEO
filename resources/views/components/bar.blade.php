@props(['value', 'max', 'label' => null])
@php $w = $max > 0 ? max(1, round($value / $max * 100)) : 0; @endphp
<span class="bar" title="{{ $label ?? $value }}"><span class="bar__fill" style="--w: {{ $w }}%"></span></span>
