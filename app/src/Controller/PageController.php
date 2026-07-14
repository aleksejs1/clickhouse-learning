<?php

namespace App\Controller;

use App\Service\ClickHouse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    public function __construct(private ClickHouse $clickHouse)
    {
    }

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        $count = $this->clickHouse->select('SELECT count() AS c FROM events')[0]['c'];
        $echo = $this->clickHouse->select('SELECT {x:String} AS v', ['x' => "a'b"])[0]['v'];

        return new Response(sprintf('events=%s param-echo=%s', $count, $echo));
    }
}
