<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProductApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Clean slate: remove anything left over from a previous test run.
        $this->em->createQuery('DELETE FROM App\Entity\Product')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Category')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    private function createUser(string $email, string $password, array $roles): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function getToken(string $email, string $password): string
    {
        $client = static::createClient();
        $response = $client->request('POST', '/api/login_check', [
            'json' => ['email' => $email, 'password' => $password],
        ]);

        return $response->toArray()['token'];
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/products');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanSeeUnpublishedProduct(): void
    {
        $this->createUser('admin@test.local', 'password1234', ['ROLE_ADMIN']);
        $token = $this->getToken('admin@test.local', 'password1234');

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);

        $product = new Product();
        $product->setName('Draft Blend')
            ->setSlug('draft-blend')
            ->setSku('DRAFT-999')
            ->setBasePrice('7.50')
            ->setPublished(false)
            ->setCategory($category);
        $this->em->persist($product);
        $this->em->flush();

        $client = static::createClient();
        $client->request('GET', '/api/products/'.$product->getId(), [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $this->assertResponseStatusCodeSame(200);
    }

    public function testMobileAppCannotSeeUnpublishedProduct(): void
    {
        $this->createUser('mobile@test.local', 'password1234', ['ROLE_MOBILE_APP']);
        $token = $this->getToken('mobile@test.local', 'password1234');

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);

        $product = new Product();
        $product->setName('Draft Blend')
            ->setSlug('draft-blend')
            ->setSku('DRAFT-998')
            ->setBasePrice('7.50')
            ->setPublished(false)
            ->setCategory($category);
        $this->em->persist($product);
        $this->em->flush();

        $client = static::createClient();
        $client->request('GET', '/api/products/'.$product->getId(), [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMobileAppCannotCreateProduct(): void
    {
        $this->createUser('mobile2@test.local', 'password1234', ['ROLE_MOBILE_APP']);
        $token = $this->getToken('mobile2@test.local', 'password1234');

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);
        $this->em->flush();

        $client = static::createClient();
        $client->request('POST', '/api/products', [
            'headers' => ['Authorization' => 'Bearer '.$token],
            'json' => [
                'name' => 'Blocked',
                'slug' => 'blocked',
                'sku' => 'BLOCK-999',
                'basePrice' => '1.00',
                'published' => true,
                'category' => '/api/categories/'.$category->getId(),
            ],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMobileAppCollectionOnlyContainsPublishedProducts(): void
    {
        $this->createUser('mobile-list@test.local', 'password1234', ['ROLE_MOBILE_APP']);
        $token = $this->getToken('mobile-list@test.local', 'password1234');

        $category = new Category();
        $category->setName('Blends')->setSlug('blends');
        $this->em->persist($category);

        foreach ([['Live Blend', 'live-blend', 'LIVE-001', true], ['Draft Blend', 'draft-blend-list', 'DRAFT-001', false]] as [$name, $slug, $sku, $published]) {
            $product = new Product();
            $product->setName($name)->setSlug($slug)->setSku($sku)->setBasePrice('7.50')
                ->setPublished($published)->setCategory($category);
            $this->em->persist($product);
        }
        $this->em->flush();

        $client = static::createClient();
        $client->request('GET', '/api/products', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        $this->assertResponseIsSuccessful();
        $skus = array_column($client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'] ?? [], 'sku');
        $this->assertContains('LIVE-001', $skus);
        $this->assertNotContains('DRAFT-001', $skus);
    }
}
