<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1', name: 'api_v1_')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Deserializer $deserializer,
        private readonly UserRepository $repository,
    )
    {
    }

    #[Route('/profile', name: 'update_profile', methods: 'PUT')]
    #[OA\Put(
        path: '/api/v1/profile',
        summary: 'Update current user profile',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/User')
        ),
        tags: ['Profile'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'response',
                            properties: [
                                new OA\Property(
                                    property: 'user',
                                    ref: '#/components/schemas/User'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Authentication required'),
            new OA\Response(response: 404, description: 'Current user not found')
        ]
    )]
    #[IsGranted('users.self.update')]
    public function update(): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof UserInterface) {
            return new JsonResponse([
                'error' => 'Authentication required.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $this->repository->getUserById($currentUser->getUserIdentifier());
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => 'User not found.'
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->getIsSystem()) {
            return new JsonResponse([
                'error' => 'System users cannot be updated.'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->deserializer->deserialize($user, ['profile_update'])) {
            return $this->deserializer->respondWithError();
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $context = (new ObjectNormalizerContextBuilder())
            ->withGroups('response_without_password')
            ->toArray();

        return new JsonResponse([
            'response' => ['user' => json_decode($this->serializer->serialize($user, 'json', $context))]
        ]);
    }
}
