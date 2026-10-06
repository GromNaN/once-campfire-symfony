<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\CustomStylesData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Form of the custom CSS of the account.
 *
 * The editor is a page of its own while the styles are saved by another
 * address, so the form carries the address it posts to.
 */
final class CustomStylesType extends AbstractType
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('customStyles', TextareaType::class, [
            'label' => 'Custom CSS',
            'required' => false,
            'attr' => [
                'rows' => 16,
                'placeholder' => 'Add CSS styles...',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
                'autocorrect' => 'off',
                'autocapitalize' => 'off',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomStylesData::class,
            'method' => 'PUT',
            'action' => $this->urlGenerator->generate('account_custom_styles_update'),
        ]);
    }
}
