<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A list of people to pick from, rendered as checkboxes.
 *
 * The choices are handed in by the form that uses it, so a plain choice list is
 * enough. The Doctrine entity type would wrap the picked people in a collection,
 * which the form data holds as a plain list.
 */
final class MemberChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => [],
            'choice_label' => 'name',
            'choice_value' => static fn (?User $user): string => (string) $user?->getId(),
            'multiple' => true,
            'expanded' => true,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
