<?php

namespace WorkflowFixture\App\Services;

/** A call chain deeper than the interpreter's depth bound. */
class DeepChain
{
    public function d1(): int
    {
        return $this->d2();
    }

    public function d2(): int
    {
        return $this->d3();
    }

    public function d3(): int
    {
        return $this->d4();
    }

    public function d4(): int
    {
        return $this->d5();
    }

    public function d5(): int
    {
        return $this->d6();
    }

    public function d6(): int
    {
        return $this->d7();
    }

    public function d7(): int
    {
        return $this->d8();
    }

    public function d8(): int
    {
        return $this->d9();
    }

    public function d9(): int
    {
        return $this->d10();
    }

    public function d10(): int
    {
        return $this->d11();
    }

    public function d11(): int
    {
        return $this->d12();
    }

    public function d12(): int
    {
        return $this->d13();
    }

    public function d13(): int
    {
        return $this->d14();
    }

    public function d14(): int
    {
        return $this->d15();
    }

    public function d15(): int
    {
        return $this->d16();
    }

    public function d16(): int
    {
        return 16;
    }
}
