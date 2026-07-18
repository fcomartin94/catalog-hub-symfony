<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Category;
use App\Entity\PriceList;
use App\Entity\Product;
use App\Entity\StoreChannel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests the outbound sync endpoint against mocked HTTP responses shaped like
 * PrestaShop's and WooCommerce's real APIs — the same approach
 * stock-sync-hub-laravel uses with Http::fake() for the inbound direction,
 * and for the same reason: no live store exists to sync against in this
 * portfolio, but the connector code itself makes no assumption about
 * mocking and would work unchanged against a real store with valid
 * credentials and a real base URL.
 */
final class StoreChannelSyncApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\PriceList')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Product')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\StoreChannel')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    private function createAdminAndToken(): string
    {
        $user = new User();
        $user->setEmail('sync-admin@test.local');
        $user->setRoles(['ROLE_ADMIN']);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password1234'));

        $this->em->persist($user);
        $this->em->flush();

        $client = static::createClient();
        $response = $client->request('POST', '/api/login_check', [
            'json' => ['email' => 'sync-admin@test.local', 'password' => 'password1234'],
        ]);

        return $response->toArray()['token'];
    }

    public function testSyncPushesToPrestaShopAndReportsSuccess(): void
    {
        $token = $this->createAdminAndToken();

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);

        $product = new Product();
        $product->setName('Ethiopia Yirgacheffe')
            ->setSlug('ethiopia')
            ->setSku('COF-100')
            ->setBasePrice('12.50')
            ->setPublished(true)
            ->setCategory($category);
        $this->em->persist($product);

        $channel = new StoreChannel();
        $channel->setName('Brew & Co.')
            ->setPlatform('prestashop')
            ->setBaseUrl('https://brewco.example.com');
        $this->em->persist($channel);

        $priceList = new PriceList();
        $priceList->setProduct($product)->setChannel($channel)->setPrice('14.90');
        $this->em->persist($priceList);

        $this->em->flush();

        // Mock PrestaShop's Webservice API responding 200 to the product PUT,
        // matching the response shape the real connector expects — only the
        // transport is faked, not the connector's request-building logic.
        //
        // Order matters here: static::createClient() shuts down and reboots
        // the kernel internally, which discards any prior getContainer()
        // set() call against the old kernel's container. The client must be
        // created first, and set() called on ITS container afterwards, so
        // the substitution survives into the kernel that actually serves
        // this request.
        $client = static::createClient();

        $mockClient = new MockHttpClient([
            new MockResponse('<prestashop><product><id>42</id></product></prestashop>', ['http_code' => 200]),
        ]);
        $client->getContainer()->set('store_sync.http_client', $mockClient);

        $client->request('POST', '/api/store_channels/'.$channel->getId().'/sync', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['synced' => 1, 'failed' => []]);
    }

    public function testSyncReportsFailureWithoutAbortingRequest(): void
    {
        $token = $this->createAdminAndToken();

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);

        $product = new Product();
        $product->setName('Ethiopia Yirgacheffe')
            ->setSlug('ethiopia-2')
            ->setSku('COF-101')
            ->setBasePrice('12.50')
            ->setPublished(true)
            ->setCategory($category);
        $this->em->persist($product);

        $channel = new StoreChannel();
        $channel->setName('Brew & Co.')
            ->setPlatform('prestashop')
            ->setBaseUrl('https://brewco.example.com');
        $this->em->persist($channel);

        $priceList = new PriceList();
        $priceList->setProduct($product)->setChannel($channel)->setPrice('14.90');
        $this->em->persist($priceList);

        $this->em->flush();

        // Simulate PrestaShop rejecting the payload (e.g. validation error).
        // Same ordering requirement as the previous test: create the client
        // first, then set() on its container.
        $client = static::createClient();

        $mockClient = new MockHttpClient([
            new MockResponse('<prestashop><errors><error><code>21</code></error></errors></prestashop>', ['http_code' => 400]),
        ]);
        $client->getContainer()->set('store_sync.http_client', $mockClient);

        $client->request('POST', '/api/store_channels/'.$channel->getId().'/sync', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        // The sync endpoint itself still returns 200 — a per-product failure
        // is reported in the payload, not raised as an HTTP error for the
        // whole request, since one bad product shouldn't fail an entire
        // channel sync.
        $this->assertResponseStatusCodeSame(200);
        $json = $client->getResponse()->toArray(false);
        $this->assertSame(0, $json['synced']);
        $this->assertCount(1, $json['failed']);
        $this->assertStringContainsString('COF-101', $json['failed'][0]);
    }

    public function testSyncIsForbiddenForMobileAppRole(): void
    {
        $user = new User();
        $user->setEmail('sync-mobile@test.local');
        $user->setRoles(['ROLE_MOBILE_APP']);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password1234'));
        $this->em->persist($user);

        $channel = new StoreChannel();
        $channel->setName('Brew & Co.')
            ->setPlatform('prestashop')
            ->setBaseUrl('https://brewco.example.com');
        $this->em->persist($channel);

        $this->em->flush();

        $client = static::createClient();
        $loginResponse = $client->request('POST', '/api/login_check', [
            'json' => ['email' => 'sync-mobile@test.local', 'password' => 'password1234'],
        ]);
        $token = $loginResponse->toArray()['token'];

        $client = static::createClient();
        $client->request('POST', '/api/store_channels/'.$channel->getId().'/sync', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }
}
