<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\RoomData;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of a direct room: only the people to talk to.
 */
final class DirectRoomType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('members', MemberChoiceType::class, [
            'label' => 'People',
            // The people are picked by name, so the list is folded into a
            // select that the picker fills in, rather than shown as checkboxes.
            'expanded' => false,
            'choices' => $this->users->findActiveOrdered(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomData::class,
            // A conversation is named after the people in it, so only the group
            // that requires members runs.
            'validation_groups' => ['Default', 'members'],
        ]);
    }
}
