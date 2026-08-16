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
        // Registry-only, no HTTP. This subscriber must never call Elasticsearch: Twig's
        // tabler_menu_has_items() builds the menu to test emptiness and the component builds it
        // again to render, so the event fires ~3x per page. An earlier version called reports()
        // here, which inspected every index on every dispatch — 80 requests on one page load.
        if (!$this->indexService->hasElasticSearches()) {
            return;
        }

        $submenu = $this->addSubmenu($event->getMenu(), 'Elastic', 'tabler:search');

        // No warning badge here on purpose. It would need the full inspection above, and the
        // count lives one click away on the page it describes.
        $this->add($submenu, 'survos_elastic_admin_index', label: 'Indexes', icon: 'tabler:list-check');

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
