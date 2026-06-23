@props([
    'steps' => [],
])

<div {{ $attributes->merge(['class' => 'checkout-steps']) }}>
    @foreach ($steps as $step)
        @php
            $key = $step['key'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $step['label'] ?? 'step'));
            $state = $step['state'] ?? '';
            $icon = $step['icon'] ?? 'check-circle';
        @endphp

        <div class="checkout-step {{ $state }}" data-checkout-step="{{ $key }}">
            <span class="checkout-step-marker">
                <x-ui.icon :name="$icon" class="h-4 w-4" />
            </span>
            <span class="min-w-0">
                <span class="checkout-step-label">{{ $step['label'] ?? $key }}</span>
                @if (! empty($step['caption']))
                    <span class="checkout-step-caption">{{ $step['caption'] }}</span>
                @endif
            </span>
        </div>
    @endforeach
</div>
