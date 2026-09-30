<?php
declare(strict_types=1);

namespace App\Controller;

use App\Api\DTO\User\AuthUserRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Context\Normalizer\ObjectNormalizerContextBuilder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Attributes as OA;

#[Route('/api/v1', name: 'api_v1_')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Deserializer $deserializer,
        private readonly UserRepository $repository,
        private readonly ValidatorInterface $validator,
    )
    {
    }

    #[Route('/user/{id}/scope', name: 'get_user_scopes', methods: 'GET')]
    #[OA\Get(
        path: '/api/v1/user/{id}/scope',
        summary: 'Fetch scopes granted to a user for an audience',
        tags: ['User'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the user',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'audience',
                description: 'Protected-resource audience',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uri', maxLength: 3000)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Scopes fetched successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'response',
                            properties: [
                                new OA\Property(
                                    property: 'scopes',
                                    type: 'array',
                                    items: new OA\Items(type: 'string')
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid audience'),
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    #[IsGranted('users.read')]
    public function getScopes(
        #[MapEntity(id: 'id')] User $user,
        Request $request,
    ): Response {
        $audience = $request->query->getString('audience');
        $violations = $this->validator->validate($audience, [
            new Assert\NotBlank(),
            new Assert\Url(protocols: ['http', 'https']),
            new Assert\Length(min: 1, max: 3000),
        ]);

        if (count($violations) > 0) {
            return new JsonResponse([
                'error' => 'Invalid audience. ' . $violations[0]->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'response' => [
                'scopes' => $this->repository->getScopes($user, $audience),
            ],
        ]);
    }

    #[Route('/user/{id}', name: 'fetch_user', methods: 'GET')]
    #[OA\Get(
        path: '/api/v1/user/{id}',
        summary: 'Fetch a user by ID',
        tags: ['User'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the user',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid'
                )
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User fetched successfully',
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
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    #[IsGranted('users.read')]
    public function fetch(#[MapEntity(id: 'id')] User $user): Response
    {
        return new JsonResponse([
            'response' => ['user' => json_decode($this->serializer->serialize($user, 'json'))]
        ]);
    }

    #[Route('/user', name: 'create_user', methods: 'POST')]
    #[OA\Post(
        path: '/api/v1/user',
        summary: 'Create a new user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/User')
        ),
        tags: ['User'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User created successfully',
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
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    #[IsGranted('users.write')]
    public function create(): Response
    {
        $user = new User();
        if (!$this->deserializer->deserialize($user, ['create'])) {
            return $this->deserializer->respondWithError();
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $context = (new ObjectNormalizerContextBuilder())
            ->withGroups('response_without_password')
            ->toArray();

        return new JsonResponse([
            'response' => ['user' => json_decode($this->serializer->serialize($user, 'json', $context))]
        ], Response::HTTP_CREATED);
    }

    #[Route('/user/{id}', name: 'update_user', methods: 'PUT')]
    #[OA\Put(
        path: '/api/v1/user/{id}',
        summary: 'Update an existing user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/User')
        ),
        tags: ['User'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the user',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid'
                )
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User updated successfully',
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
            new OA\Response(response: 403, description: 'System users cannot be updated'),
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    #[IsGranted('users.write')]
    public function update(#[MapEntity(id: 'id')] User $user): Response
    {
        if ($user->getIsSystem()) {
            return new JsonResponse([
                'error' => 'System users cannot be updated.'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->deserializer->deserialize($user, ['update'])) {
            return $this->deserializer->respondWithError();
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'response' => ['user' => json_decode($this->serializer->serialize($user, 'json'))]
        ]);
    }

    #[Route('/user/{id}', name: 'delete_user', methods: 'DELETE')]
    #[OA\Delete(
        path: '/api/v1/user/{id}',
        summary: 'Delete a user',
        tags: ['User'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the user',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid'
                )
            )
        ],
        responses: [
            new OA\Response(response: 204, description: 'User deleted'),
            new OA\Response(response: 403, description: 'System users cannot be deleted'),
            new OA\Response(response: 404, description: 'User not found')
        ]
    )]
    #[IsGranted('users.delete')]
    public function delete(#[MapEntity(id: 'id')] User $user): Response
    {
        if ($user->getIsSystem()) {
            return new JsonResponse([
                'error' => 'System users cannot be deleted.'
            ], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/auth', name: 'auth', methods: 'POST')]
    #[OA\Post(
        path: '/api/v1/auth',
        summary: 'Authenticate user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AuthUserRequest')
        ),
        tags: ['User'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authentication successful',
                content: new OA\JsonContent(ref: '#/components/schemas/User')
            ),
            new OA\Response(response: 400, description: 'Invalid credentials')
        ]
    )]
    #[IsGranted('users.auth')]
    public function auth(): Response
    {
        $authRequest = new AuthUserRequest();
        if (!$this->deserializer->deserialize($authRequest)) {
            return $this->deserializer->respondWithError();
        }

        $user = $this->repository->getUserByUsernameAndPassword(
            $authRequest->getUsername(), $authRequest->getPassword());
        if ($user === null) {
            return new JsonResponse([
                'error' => 'User not found.'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!empty($authRequest->grantType) && !in_array($authRequest->grantType, $user->getGrantTypes())) {
            return new JsonResponse([
                'error' => 'Invalid grant type.'
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'response' => ['user' => json_decode($this->serializer->serialize($user, 'json'))]
        ]);
    }
}
