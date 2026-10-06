<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\AccountUserRoleData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of the role of a member, a single switch saved on its own.
 */
final class AccountUserRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('administrator', CheckboxType::class, [
            'label' => 'Administrator',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountUserRoleData::class,
            'method' => 'PATCH',
        ]);
    }
}
