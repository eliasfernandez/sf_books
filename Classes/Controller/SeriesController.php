<?php
namespace Evoweb\SfBooks\Controller;

/**
 * This file is developed by evoweb.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * Plugin 'Book Library - Series' for the 'sf_books' extension.
 *
 * @package Evoweb\SfBooks\Controller
 */
class SeriesController extends AbstractController
{
    /**
     * @var array
     */
    protected $allowedOrderBy = ['title'];

    /**
     * @var \Evoweb\SfBooks\Domain\Repository\SeriesRepository
     */
    protected $repository;

    /**
     * @param \Evoweb\SfBooks\Domain\Repository\SeriesRepository $repository
     */
    public function injectRepository(\Evoweb\SfBooks\Domain\Repository\SeriesRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return void
     */
    protected function initializeAction()
    {
        $this->setDefaultOrderings();
    }

    /**
     * renders the list of books with search and pagination
     *
     * @return void
     */
    protected function listAction()
    {
        $seriesGroups = $this->repository->findSeriesGroupedByLetters();

        $this->view->assign('seriesGroups', $seriesGroups);
    }

    /**
     * renders the content for a single series
     *
     * @param \Evoweb\SfBooks\Domain\Model\Series $series
     *
     * @return void
     */
    protected function showAction(\Evoweb\SfBooks\Domain\Model\Series $series)
    {
        // This sets the title of the page for use in indexed search results:
        if ($series->getTitle()) {
            $this->getTypoScriptFrontendController()->indexedDocTitle = $series->getTitle();
        }

        $this->view->assign('series', $series);
    }
}
