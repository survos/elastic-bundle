<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Controller;

use Survos\ElasticBundle\Service\ElasticIndexService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tabler admin pages for the Elasticsearch indexes this app declares.
 *
 * Scope is deliberately narrow. Browsing documents, running raw queries, managing shards and
 * snapshots are all things Elasticvue and Kibana already do better than we ever will, and the
 * navbar links straight to them. What a generic GUI structurally *cannot* do is tell you whether
 * the index matches what the app declared — only the app knows what it declared. That comparison
 * is the whole reason these pages exist.
 *
 * No IndexInfo entity, no sync: everything is read live. meili-bundle's index registry was built
 * for 1000+ indexes and tenants cover that case now.
 */
final class ElasticAdminController extends AbstractController
{
    public function __construct(
        private readonly ElasticIndexService $indexService,
        // Symfony's own IDE setting (framework.ide), so "open in IDE" needs no config of ours.
        #[Autowire('%debug.file_link_format%')] private readonly string|false|null $fileLinkFormat = null,
    ) {
    }

    #[Route('/', name: 'survos_elastic_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SurvosElastic/admin/index.html.twig', [
            'reports' => $this->indexService->reports(),
        ]);
    }

    #[Route('/{code}', name: 'survos_elastic_admin_show', methods: ['GET'])]
    public function show(string $code): Response
    {
        $report = $this->indexService->report($code);
        if (null === $report) {
            throw $this->createNotFoundException(sprintf('No Elasticsearch-backed search is registered as "%s".', $code));
        }

        return $this->render('@SurvosElastic/admin/show.html.twig', [
            'report' => $report,
            'ideLink' => $this->ideLink($report->entityClass),
        ]);
    }

    /** A `phpstorm://`-style link to the entity, using whatever framework.ide is set to. */
    private function ideLink(?string $class): ?string
    {
        if (null === $class || !\is_string($this->fileLinkFormat) || '' === $this->fileLinkFormat || !class_exists($class)) {
            return null;
        }

        $file = (new \ReflectionClass($class))->getFileName();
        if (false === $file) {
            return null;
        }

        return str_replace(['%f', '%l'], [$file, '1'], $this->fileLinkFormat);
    }
}
