<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Entity\Book;
use App\Repository\AuthorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
// use Symfony\Component\Serializer\SerializerInterface; // Serealiser natif de symfony
//use JMS\Serializer\Serializer; // serialiser de JMSSerializer
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class BookController extends AbstractController
{
    #[Route('/api/books', name: 'books', methods: ['GET'])]
    /**
     * Summary of getBookList
     * @param \App\Repository\BookRepository $bookRepository
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getBookList(BookRepository $bookRepository, SerializerInterface $serializer, 
    Request $request, TagAwareCacheInterface $cachePool): JsonResponse
    {
        // Système de pagination
        $page = $request->get('page', 1); // NB: page ou offset sont pareils et représente le numéro de la page à partir de laquelle on envoie les resultats
        $limit = $request->get('limit', 3); // Nombre d'éléments à générer par page

        // Système de cache
        $idCache = "getBookList-" . $page . "-" . $limit;
        $jsonBookList = $cachePool->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            echo("L'élément n'a pas encore été mis en cache");
            $item->tag("booksCache");
            $item->expiresAfter(60); // Permet de définir la durée de vie en secondes des données mise en cache avec un tag précis
            $bookList = $bookRepository->findAllWithPagination($page, $limit);

            $context = SerializationContext::create()->setGroups(['getBooks']);
            return $serializer->serialize($bookList, 'json', $context);
        });

        return new JsonResponse(
            $jsonBookList,
            Response::HTTP_OK,
            [],
            true,
        );
    }

    #[Route('/api/books/{id}', name: 'detailbook', methods: ['GET'])]
    /**
     * Summary of getSingleBook
     * @param \App\Entity\Book $book
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getSingleBook(Book $book, SerializerInterface $serializer) : JsonResponse
    {
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        return new JsonResponse(
            $jsonBook,
            Response::HTTP_OK,
            ['accept' => 'json'],
            true,
        );
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un livre')]
    /**
     * Summary of deleteBook
     * @param \App\Entity\Book $book
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse 
    {
        $cachePool->invalidateTags(["booksCache"]);
        $em->remove($book);
        $em->flush();

        return new JsonResponse(
            null, 
            Response::HTTP_NO_CONTENT
        );
    }

    #[Route('/api/books', name:"createBook", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    /**
     * Summary of createBook
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @param \Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    #[Route('/api/books', name:"createBook", methods: ['POST'])]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, 
    UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator): JsonResponse 
    {
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');

        // On vérifie les erreurs
        $errors = $validator->validate($book);

        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
            //throw new HttpException(JsonResponse::HTTP_BAD_REQUEST, "La requête est invalide");
        }

        $em->persist($book);
        $em->flush();

        // Récupération de l'ensemble des données envoyées sous forme de tableau
        $content = $request->toArray();

        // Récupération de l'idAuthor. S'il n'est pas défini, alors on met -1 par défaut.
        $idAuthor = $content['idAuthor'] ?? -1;

        // On cherche l'auteur qui correspond et on l'assigne au livre.
        // Si "find" ne trouve pas l'auteur, alors null sera retourné.
        $book->setAuthor($authorRepository->find($idAuthor));

        //send data
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        
        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse(
            $jsonBook, 
            Response::HTTP_CREATED, 
            ["Location" => $location], 
            true
        );
   }

   #[Route('/api/books/{id}', name:"updateBook", methods:['PUT'])]
   #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour modifier un livre')]
    /**
     * Summary of updateBook
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Symfony\Component\Serializer\SerializerInterface $serializer
     * @param \App\Entity\Book $currentBook
     * @param \Doctrine\ORM\EntityManagerInterface $em
     * @param \App\Repository\AuthorRepository $authorRepository
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function updateBook(Request $request, SerializerInterface $serializer, Book $currentBook, 
    EntityManagerInterface $em, AuthorRepository $authorRepository): JsonResponse 
    {
        $updatedBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($updatedBook->getTitle());
        $currentBook->setCoverText($updatedBook->getCoverText());

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;
        $currentBook->setAuthor($authorRepository->find($idAuthor));
        
        $em->persist($currentBook);
        $em->flush();
        return new JsonResponse(
            null, 
            JsonResponse::HTTP_NO_CONTENT
        );
   }
}
