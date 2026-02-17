<?php 

namespace Dencel\LaravelEparaksts\Callbacks;

use Dencel\LaravelEparaksts\Services\Eparaksts;

abstract class Callback
{
    public ?Eparaksts $eparaksts = null;

    abstract public function handle();

    public function getEparaksts() : ?string
    {
        return $this->eparaksts;
    }

    public function setEparaksts(Eparaksts $eparaksts) : ?Eparaksts
    {
        return $this->eparaksts = $eparaksts;
    }
}