<?php

namespace Dencel\LaravelEparaksts\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Logo extends Component
{
    public string $path = '';

    /**
     * @param string|null $type Logo variant: eparaksts (default), mobile, mobile-full,
     *                          mobile-small, eid, eid-scan, eid-scan-small, karte, ezimogs, api
     * @param string|null $class CSS class(es) applied to the <img> element
     * @param string|null $alt Alt text; defaults to empty string if omitted
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
