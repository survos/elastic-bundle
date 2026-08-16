<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Menu;

use Survos\ElasticBundle\Service\ElasticIndexService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\IconService;
use Survos\TablerBundle\Service\RouteAliasService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Elastic dropdown in the admin navbar — our own pages first, then the external GUIs.
 *
 * The external links are the point: Elasticvue and Kibana already do document browsing, raw
 * queries, shard and snapshot management better than we would, so this bundle links to them
 * rather than reimplementing any of it. Same shape as meili-bundle's link out to the riccox UI.
 */
final class ElasticMenuSubscriber
{
    use MenuBuilderTrait;

    /**
     * Where to send someone who has no Elasticvue yet.
     *
     * The extension is the path of least resistance: unlike the hosted or self-hosted web app it
     * needs no CORS on the Elasticsearch node, because extensions aren't subject to it. That is
     * why `elasticvue_url` can stay unset and this still works.
     */
    private const string ELASTICVUE_EXTENSION = 'https://chromewebstore.google.com/detail/elasticvue/hkedbapjpblbodpgbajblpnlpenaebaa';

    public function __construct(
        private readonly ElasticIndexService $indexService,
        private readonly ?string $kibanaUrl = null,
        private readonly ?string $elasticvueUrl = null,
        private readonly ?string $serverUrl = null,
        protected readonly ?RouterInterface $router = null,
        protected readonly ?RouteAliasService $routeAliasService = null,
        protected readonly ?IconService $iconService = null,
    ) {
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $reports = $this->indexService->reports();

        // Nothing Elasticsearch-backed is registered — don't put an empty dropdown in every app
        // that merely has the bundle installed transitively.
        if ($reports === []) {
            return;
        }

        $submenu = $this->addSubmenu($event->getMenu(), 'Elastic', 'tabler:search');

        $warnings = array_sum(array_map(static fn ($r): int => $r->warningCount, $reports));

        $this->add(
            $submenu,
            'survos_elastic_admin_index',
            label: 'Indexes',
            icon: 'tabler:list-check',
            // Surfacing the warning count here is the whole reason the page exists — schema drift
            // is silent otherwise.
            badge: $warnings > 0 ? (string) $warnings : null,
        );

        // Always offer Elasticvue: the configured instance when there is one, otherwise the
        // extension listing, so the menu is useful on a machine that has neither.
        $this->elasticvueUrl
            ? $this->add($submenu, uri: $this->elasticvueUrl, label: 'Elasticvue', icon: 'tabler:eye', external: true, dividerBefore: true)
            : $this->add($submenu, uri: self::ELASTICVUE_EXTENSION, label: 'Elasticvue (install)', icon: 'tabler:eye', external: true, dividerBefore: true);

        if ($this->kibanaUrl) {
            $this->add($submenu, uri: $this->kibanaUrl, label: 'Kibana', icon: 'tabler:chart-line', external: true);
        }

        if ($this->serverUrl) {
            $this->add($submenu, uri: $this->serverUrl, label: 'ES Server', icon: 'tabler:server', external: true);
        }
    }
}
