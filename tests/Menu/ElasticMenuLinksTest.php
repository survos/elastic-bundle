<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Menu;

use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Menu\ElasticMenuLinks;

// The root monorepo autoloader does not include ElasticBundle yet.
require_once dirname(__DIR__, 2).'/src/Menu/ElasticMenuLinks.php';

final class ElasticMenuLinksTest extends TestCase
{
    public function testEndpointDisplayNeverContainsCredentialsOrQuery(): void
    {
        self::assertSame(['remote' => 'https://es.example:9243'], ElasticMenuLinks::endpoints([
            'remote' => ['dsn' => 'elasticsearch+https://user:secret@es.example:9243/path?api_key=private'],
            'other' => ['dsn' => 'doctrine://default'],
        ]));
    }

    public function testRemoteOrMixedServersDoNotDefaultToLocalKibana(): void
    {
        self::assertNull(ElasticMenuLinks::kibana(null, ['http://localhost:9200', 'https://remote:9200'], true));
        self::assertNull(ElasticMenuLinks::kibana(null, [], true));
        self::assertNull(ElasticMenuLinks::kibana(null, ['http://localhost:9200'], false));
        self::assertSame('http://localhost:5601', ElasticMenuLinks::kibana(null, ['http://localhost:9200'], true));
    }

    public function testExplicitUrlSupportsSpacesAndStripsSecrets(): void
    {
        self::assertSame('https://kibana.example/s/my-space', ElasticMenuLinks::kibana(
            'https://user:secret@kibana.example/s/my-space/?token=private#fragment', [], false,
        ));
        self::assertNull(ElasticMenuLinks::kibana('', ['http://localhost:9200'], true));
        self::assertNull(ElasticMenuLinks::safeUrl('javascript:alert(1)'));
    }
}
