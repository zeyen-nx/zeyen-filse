<?php


declare(strict_types=1);

namespace Zeyen\Filse\Upload;

final readonly class PageResult
{
    /**
     * @param FileInfo[] $items
     */
    public function __construct(
        public int   $total,
        public int   $page,
        public int   $pageSize,
        public array $items,
        public array $raw = [],
    )
    {
    }

    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((array)($data['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = FileInfo::fromArray($item);
            }
        }

        return new self(
            total: (int)($data['total'] ?? 0),
            page: (int)($data['page'] ?? 1),
            pageSize: (int)($data['page_size'] ?? count($items)),
            items: $items,
            raw: $data,
        );
    }

    public function hasMore(): bool
    {
        return $this->page * $this->pageSize < $this->total;
    }
}
