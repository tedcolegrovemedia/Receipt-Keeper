<?php
declare(strict_types=1);

class HomeController
{
    public function index(): void
    {
        ensure_authenticated();
        render('home');
    }
}
