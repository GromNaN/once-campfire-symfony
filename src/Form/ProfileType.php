<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\ProfileData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of the profile: the name and picture of a user, and the details they
 * sign in with.
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('avatar', FileType::class, [
                'label' => 'Avatar',
                'required' => false,
                'attr' => ['accept' => 'image/*'],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['autocomplete' => 'name', 'autofocus' => true, 'maxlength' => 255],
            ])
            ->add('emailAddress', EmailType::class, [
                'label' => 'Email address',
                'required' => false,
                'attr' => ['autocomplete' => 'username', 'maxlength' => 255],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'New password',
                'required' => false,
                'attr' => ['autocomplete' => 'new-password', 'maxlength' => 72],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => ['maxlength' => 200, 'rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileData::class,
            'method' => 'PUT',
        ]);
    }
}
