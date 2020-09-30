<?php

namespace Brosto\Loyalty\Integrations\OneC;

class PersonalRecomendation
{
    protected $id;

    protected $productXmlIds = [];

    public function __construct(string $id, array $productXmlIds)
    {
        $this->id = $id;
        $this->productXmlIds = $productXmlIds;
    }

    public function getId(): string
    {
        return $this->id ?? '';
    }

    public function getProductXmlIds(): array
    {
        return $this->productXmlIds;
    }

    public function hasProducts(): bool
    {
        return (! empty($this->id)) && (! empty($this->productXmlIds));
    }
}
