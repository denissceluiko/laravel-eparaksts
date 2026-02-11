<?php

namespace Dencel\LaravelEparaksts\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Logo extends Component
{
    public string $path = '';

    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $type = '',
        public ?string $class = '',
        public ?string $alt = ''
    ) {
        $prefix = 'vendor/eparaksts/images/logos/eparaksts';
        $suffix = '.svg';

        $this->path = $prefix . '-' . str_replace(['..', '/'], '', $type) . $suffix;
        $this->path = asset(file_exists($this->path) ? $this->path : $prefix . $suffix);
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('eparaksts::components.logo');
    }
}
