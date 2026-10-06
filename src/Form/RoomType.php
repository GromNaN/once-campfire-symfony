<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Form\Data\RoomData;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of an open or closed room: a name, and the members to grant.
 */
final class RoomType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['autofocus' => true, 'maxlength' => 255],
            ])
            ->add('members', MemberChoiceType::class, [
                'label' => 'People',
                'required' => false,
                'choices' => $this->users->findActiveOrdered(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomData::class,
            // This form asks for a name and for the people to grant, so the
            // groups holding those constraints run alongside the default one.
            'validation_groups' => ['Default', 'room', 'members'],
        ]);
    }
}
