<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\AccountSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Form of the account switches, saved on their own so a switch is applied the
 * moment it is flipped.
 *
 * The switches live in a section of the account page of their own, so the form
 * carries the address it posts to rather than submitting to the page it is on.
 */
final class AccountSettingsType extends AbstractType
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('restrictRoomCreationToAdministrators', CheckboxType::class, [
            'label' => 'Must be admin to create new rooms',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountSettingsData::class,
            'method' => 'PATCH',
            'action' => $this->urlGenerator->generate('account_settings_update'),
        ]);
    }
}
