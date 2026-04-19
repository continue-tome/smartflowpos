<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Category;

/**
 * OrderRoutingService
 * Détermine la destination d'impression de chaque item de commande
 * en fonction de la catégorie du produit.
 *
 * Destinations: kitchen | bar | pizza
 */
class OrderRoutingService
{
    /**
     * Regroupe les items par destination
     * @param \Illuminate\Support\Collection $items  Collection de OrderItem avec product.category chargés
     * @return array ['kitchen' => [...], 'bar' => [...], 'pizza' => [...]]
     */
    public function groupByDestination($items): array
    {
        $groups = [
            'kitchen' => collect(),
            'bar'     => collect(),
            'pizza'   => collect(),
            'all'     => $items, // On garde une copie de tout
        ];

        foreach ($items as $item) {
            $destination = $this->getItemDestination($item);
            $groups[$destination]->push($item);
        }

        return $groups; // On ne filtre plus pour garder les clés attendues
    }

    /**
     * Retourne la destination d'un item de commande
     */
    public function getItemDestination(OrderItem $item): string
    {
        $category = $item->product?->category ?? null;

        if (!$category || !$category->destination) {
            return 'kitchen'; 
        }

        $dest = strtolower($category->destination);
        
        return in_array($dest, ['kitchen', 'bar', 'pizza'])
            ? $dest
            : 'kitchen';
    }

    /**
     * Retourne le label lisible d'une destination
     */
    public function destinationLabel(string $destination): string
    {
        return match(strtolower($destination)) {
            'kitchen' => 'CUISINE',
            'bar'     => 'BAR',
            'pizza'   => 'PIZZA',
            'all'     => 'GLOBAL (TOUT)',
            default   => 'PRODUCTION',
        };
    }

    /**
     * Retourne l'emoji d'une destination
     */
    public function destinationIcon(string $destination): string
    {
        return match($destination) {
            'kitchen' => '🍳',
            'bar'     => '🍺',
            'pizza'   => '🍕',
            default   => '🍳',
        };
    }
}
