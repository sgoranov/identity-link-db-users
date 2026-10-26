<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupScope;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use sgoranov\IdentityLinkShared\Serializer\Deserializer;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1/group/{groupId}/scope', name: 'api_v1_group_scope_')]
#[OA\Tag(name: 'Group Scope')]
final class GroupScopeController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly Deserializer $deserializer,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/group/{groupId}/scope',
        summary: 'List scopes assigned to a group',
        parameters: [new OA\Parameter(
            name: 'groupId',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        )],
        responses: [
            new OA\Response(response: 200, description: 'Scopes fetched successfully'),
            new OA\Response(response: 404, description: 'Group not found')
        ]
    )]
    #[IsGranted('users.groups.read')]
    public function list(#[MapEntity(id: 'groupId')] Group $group): Response
    {
        return $this->scopesResponse($group);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/group/{groupId}/scope',
        summary: 'Add a scope to a group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/GroupScope')
        ),
        parameters: [new OA\Parameter(
            name: 'groupId',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        )],
        responses: [
            new OA\Response(response: 201, description: 'Scope created successfully'),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 403, description: 'System groups cannot be updated'),
            new OA\Response(response: 404, description: 'Group not found')
        ]
    )]
    #[IsGranted('users.groups.write')]
    public function create(#[MapEntity(id: 'groupId')] Group $group): Response
    {
        if ($response = $this->rejectSystemGroup($group)) {
            return $response;
        }

        $groupScope = new GroupScope();
        $groupScope->setGroup($group);

        if (!$this->deserializer->deserialize($groupScope, ['create'])) {
            return $this->deserializer->respondWithError();
        }

        $group->addScope($groupScope);
        $this->entityManager->persist($groupScope);
        $this->entityManager->flush();

        return $this->scopeResponse($groupScope, Response::HTTP_CREATED);
    }

    #[Route('/{scopeId}', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/group/{groupId}/scope/{scopeId}',
        summary: 'Delete a group scope',
        parameters: [
            new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'scopeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))
        ],
        responses: [
            new OA\Response(response: 204, description: 'Scope deleted successfully'),
            new OA\Response(response: 403, description: 'System groups cannot be updated'),
            new OA\Response(response: 404, description: 'Group or scope not found')
        ]
    )]
    #[IsGranted('users.groups.delete')]
    public function delete(
        #[MapEntity(id: 'groupId')] Group $group,
        #[MapEntity(id: 'scopeId')] GroupScope $groupScope,
    ): Response {
        $this->assertScopeBelongsToGroup($groupScope, $group);

        if ($response = $this->rejectSystemGroup($group)) {
            return $response;
        }

        $this->entityManager->remove($groupScope);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function assertScopeBelongsToGroup(GroupScope $groupScope, Group $group): void
    {
        if ($groupScope->getGroup()->getId() !== $group->getId()) {
            throw $this->createNotFoundException('Scope not found in this group.');
        }
    }

    private function rejectSystemGroup(Group $group): ?JsonResponse
    {
        if (!$group->getIsSystem()) {
            return null;
        }

        return new JsonResponse(
            ['error' => 'System groups cannot be updated.'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function scopeResponse(GroupScope $groupScope, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'response' => [
                'scope' => json_decode($this->serializer->serialize($groupScope, 'json')),
            ],
        ], $status);
    }

    private function scopesResponse(Group $group): JsonResponse
    {
        return new JsonResponse([
            'response' => [
                'scopes' => json_decode($this->serializer->serialize($group->getScopes()->toArray(), 'json')),
            ],
        ]);
    }
}
