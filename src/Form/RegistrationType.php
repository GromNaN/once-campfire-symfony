<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\RegistrationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of a signup, shared by the first run and the join page.
 *
 * A password is asked for once, without a confirmation: the person is signing
 * up and can change it later, so asking twice only makes the page longer.
 */
final class RegistrationType extends AbstractType
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
                'attr' => ['autocomplete' => 'username', 'maxlength' => 255],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Password',
                'attr' => ['autocomplete' => 'new-password', 'maxlength' => 72],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationData::class]);
    }
}
