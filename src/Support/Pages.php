<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Closure;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;

/** @internal Whole-list reads with stable filters and cycle/identity checks. */
final class Pages
{
    /** @param Closure(array):array $fetch @return list<array<array-key,mixed>> */
    public static function all(Closure $fetch, array $query = []): array
    {
        $items = [];
        $ids = [];
        $cursors = [];
        while (true) {
            if (isset($query['page'])) {
                if (! is_string($query['page']) || isset($cursors[$query['page']])) {
                    throw new EvidenceException('Pagination repeated or malformed a cursor.');
                }
                $cursors[$query['page']] = true;
            }
            $page = $fetch($query);
            if (! isset($page['items']) || ! is_array($page['items']) || ! array_is_list($page['items'])) {
                throw new EvidenceException('Provider list response has no complete item list.');
            }
            foreach ($page['items'] as $item) {
                if (! is_array($item) || ! isset($item['id']) || ! is_string($item['id']) || $item['id'] === '' || isset($ids[$item['id']])) {
                    throw new EvidenceException('Provider list contains invalid or repeated identity.');
                }
                $ids[$item['id']] = true;
                $items[] = $item;
            }
            $next = $page['page_after'] ?? null;
            if ($next === null) {
                return $items;
            }
            if (! is_string($next) || $next === '') {
                throw new EvidenceException('Provider list cursor is malformed.');
            }
            $query['page'] = $next;
        }
    }
}
