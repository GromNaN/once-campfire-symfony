<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\RoomData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of an open room: only its name.
 *
 * An open room belongs to every member of the account, so there is nobody to
 * pick, and the page lists the people for information only.
 */
final class OpenRoomType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Name',
            'attr' => ['autofocus' => true, 'maxlength' => 255],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomData::class,
            // This form asks for a name, so the group holding that constraint
            // runs alongside the default one.
            'validation_groups' => ['Default', 'room'],
        ]);
    }

    /**
     * The form of a room is named after the room, whichever kind it is, so the
     * values it posts are the same and a page can turn into another kind of
     * page without the reader losing what they typed.
     */
    public function getBlockPrefix(): string
    {
        return 'room';
    }
}
