<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\LastRoom;
use App\Repository\SearchRepository;
use App\Room\LastVisitedRoom;
use App\Search\MessageIndex;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Searching the messages a member can reach.
 *
 * The form posts rather than gets, so that a search is recorded and can be
 * offered again from the recent searches. Following a link to a recorded search
 * only reads, so it does not move the query up the list.
 */
final class SearchesController extends AbstractController
{
    public function __construct(
        private readonly MessageIndex $index,
        private readonly SearchRepository $searches,
        private readonly LastVisitedRoom $lastRoom,
    ) {
    }

    #[Route('/searches', name: 'searches_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $query = self::sanitize($request->query->getString('q'));
        $room = $this->lastRoom->for($user, $request->cookies->get(LastRoom::COOKIE));

        return $this->render('searches/index.html.twig', [
            'query' => '' === $query ? null : $query,
            'messages' => '' === $query ? [] : $this->index->search($user, $query),
            'recent_searches' => $this->searches->findRecentFor($user),
            'return_to_room' => $room,
        ]);
    }

    #[Route('/searches', name: 'searches_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): Response
    {
        $query = self::sanitize($request->request->getString('q'));

        if ('' !== $query) {
            $this->searches->record($user, $query);
        }

        return $this->redirectToRoute(
            'searches_index',
            '' === $query ? [] : ['q' => $query],
            Response::HTTP_SEE_OTHER,
        );
    }

    #[Route('/searches/clear', name: 'searches_clear', methods: ['DELETE'])]
    #[IsCsrfTokenValid('searches_clear', tokenKey: '_csrf_token')]
    public function clear(Request $request, #[CurrentUser] User $user): Response
    {
        $this->searches->deleteFor($user);

        return $this->redirectToRoute('searches_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Reduces what a person typed to the words it holds.
     *
     * A full text query is made of words: punctuation, quotes and operators are
     * dropped here rather than handed to the index, which is what the original
     * application does before it searches and before it records a search.
     */
    private static function sanitize(string $query): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}]+/u', ' ', $query));
    }
}
