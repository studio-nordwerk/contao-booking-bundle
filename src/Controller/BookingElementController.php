<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Controller;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Infrastructure\BookingTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Content element "Terminbuchung": calendar, times, form and confirmation of one
 * booking type. The page stays cacheable; free times come from the JSON route.
 */
#[AsContentElement(type: 'appointment_booking', category: 'booking')]
final class BookingElementController extends AbstractContentElementController
{
    public function __construct(
        private readonly BookingTypes $types,
        private readonly UrlGeneratorInterface $router,
        private readonly ContentUrlGenerator $contentUrls,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $settings = $model->row();
        $type = $this->types->find((int) $settings['bookingType']);
        $published = null !== $type && '1' === (string) $type['published'];
        $template->set('available', $published);
        $template->set('booking_type', $published ? [
            'title' => (string) $type['title'],
            'duration' => (int) $type['duration'],
            'location' => (string) $type['location'],
            'timezone' => Availability::fromRow($type)->timezone,
        ] : null);
        $template->set('slots_url', $this->router->generate('nordwerk_booking_slots', ['element' => (int) $model->id]));
        $template->set('book_url', $this->router->generate('nordwerk_booking_book', ['element' => (int) $model->id]));
        $template->set('privacy_url', $this->privacyUrl((int) $settings['bookingPrivacyPage']));
        $template->set('fallback', (string) $settings['bookingFallback']);
        $template->set('element_id', (int) $model->id);
        // Changes to the booking type (title, duration, publishing) clear the cached page.
        $this->tagResponse(['contao.db.tl_booking_type.'.(int) $settings['bookingType']]);

        return $template->getResponse();
    }

    private function privacyUrl(int $pageId): string
    {
        $page = $pageId > 0 ? PageModel::findPublishedById($pageId) : null;
        if (null === $page) {
            return '';
        }

        try {
            return $this->contentUrls->generate($page);
        } catch (\Throwable) {
            return '';
        }
    }
}
