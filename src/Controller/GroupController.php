<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use OpenApi\Attributes as OA;

#[Route('/api/v1', name: 'api_v1_')]
final class GroupController extends AbstractController
{

    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Deserializer $deserializer,
    )
    {
    }

    #[Route('/group/{id}', name: 'fetch_group', methods: 'GET')]
    #[OA\Get(
        path: '/api/v1/group/{id}',
        summary: 'Fetch group by ID',
        tags: ['Group'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Group fetched successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'response',
                            properties: [
                                new OA\Property(
                                    property: 'group',
                                    ref: '#/components/schemas/Group'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Group not found')
        ]
    )]
    #[IsGranted('users.groups.read')]
    public function fetch(#[MapEntity(id: 'id')] Group $group): Response
    {
        return new JsonResponse([
            'response' => ['group' => json_decode($this->serializer->serialize($group, 'json'))]
        ]);
    }

    #[Route('/group', name: 'create_group', methods: 'POST')]
    #[OA\Post(
        path: '/api/v1/group',
        summary: 'Create a new group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Group')
        ),
        tags: ['Group'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Group created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'response',
                            properties: [
                                new OA\Property(
                                    property: 'group',
                                    ref: '#/components/schemas/Group'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input')
        ]
    )]
    #[IsGranted('users.groups.write')]
    public function create(): Response
    {
        $group = new Group();
        if (!$this->deserializer->deserialize($group, ['create'])) {
            return $this->deserializer->respondWithError();
        }

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return new JsonResponse([
            'response' => ['group' => json_decode($this->serializer->serialize($group, 'json'))]
        ], Response::HTTP_CREATED);
    }

    #[Route('/group/{id}', name: 'update_group', methods: 'PUT')]
    #[OA\Put(
        path: '/api/v1/group/{id}',
        summary: 'Update an existing group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/Group')
        ),
        tags: ['Group'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Group updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'response',
                            properties: [
                                new OA\Property(
                                    property: 'group',
                                    ref: '#/components/schemas/Group'
                                )
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 403, description: 'System groups cannot be updated'),
            new OA\Response(response: 404, description: 'Group not found')
        ]
    )]
    #[IsGranted('users.groups.write')]
    public function update(#[MapEntity(id: 'id')] Group $group): Response
    {
        if ($group->getIsSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be updated.'
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->deserializer->deserialize($group, ['update'])) {
            return $this->deserializer->respondWithError();
        }

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return new JsonResponse([
            'response' => ['group' => json_decode($this->serializer->serialize($group, 'json'))]
        ]);
    }

    #[Route('/group/{id}', name: 'delete_group', methods: 'DELETE')]
    #[OA\Delete(
        path: '/api/v1/group/{id}',
        summary: 'Delete a group',
        tags: ['Group'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'UUID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(response: 204, description: 'Group deleted successfully'),
            new OA\Response(response: 403, description: 'System groups cannot be deleted'),
            new OA\Response(response: 404, description: 'Group not found')
        ]
    )]
    #[IsGranted('users.groups.delete')]
    public function delete(#[MapEntity(id: 'id')] Group $group): Response
    {
        if ($group->getIsSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be deleted.'
            ], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($group);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
