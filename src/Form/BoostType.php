<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Boost;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The short reaction someone adds to a message.
 *
 * The column holds sixteen characters, which is what the field accepts: enough
 * for a few emoji, too little for a second message.
 */
final class BoostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextType::class, [
            'label' => false,
            'attr' => [
                'autocomplete' => 'off',
                'autocorrect' => 'off',
                'maxlength' => 16,
                'placeholder' => 'Add a boost',
            ],
            'constraints' => [
                new NotBlank(message: 'Write something to boost with.'),
                new Length(max: 16),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Boost::class]);
    }
}
