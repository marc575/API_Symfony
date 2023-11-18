<?php

namespace App\Controller;

use App\Repository\AuthorRepository;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class AuthorController extends AbstractController
{
    #[Route('/api/authors', name: 'authors', methods: ['GET'])]
    /**
     * Summary of getAuthorList
     * @param \App\Repository\AuthorRepository $AuthorRepository
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getAuthorList(AuthorRepository $authorRepository, SerializerInterface $serializer, 
    Request $request, TagAwareCacheInterface $cachePool): JsonResponse
    {
        // Système de pagination
        $page = $request->get('page', 1); // NB: page ou offset sont pareils et représente le numéro de la page à partir de laquelle on envoie les resultats
        $limit = $request->get('limit', 3); // Nombre d'éléments à générer par page

        // Système de cache
        $idCache = "getAuthorList-" . $page . "-" . $limit;
        $jsonAuthorList = $cachePool->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit, $serializer) {
            echo("L'élément n'a pas encore été mis en cache");
            $item->tag("authorsCache");
            $item->expiresAfter(60); // Permet de définir la durée de vie en secondes des données mise en cache avec un tag précis
            $authorList = $authorRepository->findAllWithPagination($page, $limit);
            return $serializer->serialize($authorList, 'json', ['groups' => 'getAuthors']);
        });
        
        return new JsonResponse(
            $jsonAuthorList,
            Response::HTTP_OK,
            [],
            true,
        );
    }

    #[Route('/api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    /**
     * Summary of getSingleAuthor
     * @param \App\Entity\Author $author
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getSingleAuthor(Author $author, SerializerInterface $serializer) : JsonResponse
    {
        $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);
        return new JsonResponse(
            $jsonAuthor,
            Response::HTTP_OK,
            ['accept' => 'json'],
            true,
        );
    }

    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un auteur')]
    /**
     * Summary of deleteAuthor
     * @param \App\Entity\Author $author
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function deleteAuthor(Author $author, EntityManagerInterface $em, TagAwareCacheInterface $cachePool) : JsonResponse
    {
        $cachePool->invalidateTags(["authorsCache"]);
        $em->remove($author);
        $em->flush();
        //dd($author->getBooks());
        return new JsonResponse(
            null,
            Response::HTTP_NO_CONTENT
        );
    }

    #[Route('/api/authors', name:"createAuthor", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un auteur')]
    /**
     * Summary of createAuthor
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @param \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, 
    UrlGeneratorInterface $urlGenerator): JsonResponse 
    {
        //save data in database
        $author = $serializer->deserialize($request->getContent(), Author::class, 'json');
        $em->persist($author);
        $em->flush();

        //send data
        $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);
        
        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse(
            $jsonAuthor, 
            Response::HTTP_CREATED, 
            ["Location" => $location], 
            true
        );
   }

   #[Route('/api/authors/{id}', name:"updateAuthor", methods:['PUT'])]
   #[IsGranted('ROLE_ADMIN', message: "Vous n'avez pas les droits suffisants pour modifier les informations d'un auteur")]
    /**
     * Summary of updateAuthor
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @param \App\Entity\Author $currentAuthor
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateAuthor(Request $request, SerializerInterface $serializer, Author $currentAuthor, 
    EntityManagerInterface $em): JsonResponse 
    {
        $updatedAuthor = $serializer->deserialize($request->getContent(), Author::class, 'json', [AbstractNormalizer::OBJECT_TO_POPULATE => $currentAuthor]);
        
        $em->persist($updatedAuthor);
        $em->flush();

        return new JsonResponse(
            null, 
            JsonResponse::HTTP_NO_CONTENT
        );
   }
}
