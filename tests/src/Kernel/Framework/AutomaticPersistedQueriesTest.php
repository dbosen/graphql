<?php

namespace Drupal\Tests\graphql\Kernel\Framework;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\graphql\Kernel\GraphQLTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the automatic persisted query plugin.
 *
 * @group graphql
 */
class AutomaticPersistedQueriesTest extends GraphQLTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dynamic_page_cache',
  ];

  /**
   * Test plugin.
   *
   * @var \Drupal\graphql\Plugin\PersistedQueryPluginInterface
   */
  protected $pluginApq;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configureCachePolicy(900);

    $schema = <<<GQL
      schema {
        query: Query
      }
      type Query {
        node(id: String): Node
        field_one: String
      }

      type Node {
        title: String!
      }
GQL;
    $this->setUpSchema($schema);

    /** @var \Drupal\graphql\Plugin\DataProducerPluginManager $manager */
    $manager = $this->container->get('plugin.manager.graphql.persisted_query');
    $this->pluginApq = $manager->createInstance('automatic_persisted_query');

    // Before adding the persisted query plugins to the server, we want to make
    // sure that there are no existing plugins already there.
    $this->server->removeAllPersistedQueryInstances();
    $this->server->addPersistedQueryInstance($this->pluginApq);
    $this->server->save();
  }

  /**
   * Test the automatic persisted queries plugin.
   */
  public function testAutomaticPersistedQueries(): void {
    $this->mockResolver('Query', 'field_one', 'this is the field one');

    $endpoint = $this->server->get('endpoint');

    $query = 'query { field_one } ';
    $parameters['extensions']['persistedQuery']['sha256Hash'] = 'some random hash';

    // Check we get PersistedQueryNotFound.
    $request = Request::create($endpoint, 'GET', $parameters);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame([
      'errors' => [
        [
          'message' => 'PersistedQueryNotFound',
          'extensions' => ['category' => 'request'],
        ],
      ],
    ], json_decode($result->getContent(), TRUE));

    // Post query to endpoint with a not matching hash.
    $content = json_encode(['query' => $query] + $parameters);
    $request = Request::create($endpoint, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame([
      'errors' => [
        [
          'message' => 'Provided sha does not match query',
          'extensions' => ['category' => 'graphql'],
        ],
      ],
    ], json_decode($result->getContent(), TRUE));

    // Post query to endpoint to get the result and cache it.
    $parameters['extensions']['persistedQuery']['sha256Hash'] = hash('sha256', $query);

    $content = json_encode(['query' => $query] + $parameters);
    $request = Request::create($endpoint, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame(['data' => ['field_one' => 'this is the field one']], json_decode($result->getContent(), TRUE));

    // Execute first request again.
    $request = Request::create($endpoint, 'GET', $parameters);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame(['data' => ['field_one' => 'this is the field one']], json_decode($result->getContent(), TRUE));
  }

  /**
   * Test APQ with dynamic page cache.
   *
   * Tests that cache context for different variables parameter is correctly
   * added to the dynamic page cache entries.
   */
  public function testPageCacheWithDifferentVariables(): void {
    NodeType::create([
      'type' => 'test',
      'name' => 'Test',
    ])->save();

    $node = Node::create([
      'nid' => 1,
      'title' => 'Node 1',
      'type' => 'test',
    ]);
    $node->save();

    $node = Node::create([
      'nid' => 2,
      'title' => 'Node 2',
      'type' => 'test',
    ]);
    $node->save();

    $this->mockResolver('Query', 'node',
      $this->builder->produce('entity_load')
        ->map('type', $this->builder->fromValue('node'))
        ->map('id', $this->builder->fromArgument('id'))
    );

    $this->mockResolver('Node', 'title',
      $this->builder->produce('entity_label')
        ->map('entity', $this->builder->fromParent())
    );

    $endpoint = $this->server->get('endpoint');

    // Post query to endpoint to get the result and cache it.
    $query = 'query($id: String!) { node(id: $id) { title } }';
    $parameters['extensions']['persistedQuery']['sha256Hash'] = hash('sha256', $query);
    $parameters['variables'] = '{"id": "2"}';
    $content = json_encode(['query' => $query] + $parameters);
    $request = Request::create($endpoint, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame(['data' => ['node' => ['title' => 'Node 2']]], json_decode($result->getContent(), TRUE));

    // Execute apq call.
    $parameters['variables'] = '{"id": "1"}';
    $request = Request::create($endpoint, 'GET', $parameters);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame(['data' => ['node' => ['title' => 'Node 1']]], json_decode($result->getContent(), TRUE));

    // Execute apq call with different variables.
    $parameters['variables'] = '{"id": "2"}';
    $request = Request::create($endpoint, 'GET', $parameters);
    $result = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame(['data' => ['node' => ['title' => 'Node 2']]], json_decode($result->getContent(), TRUE));
  }

}
