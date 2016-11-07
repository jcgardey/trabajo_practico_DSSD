<?php

	namespace AppBundle\Form;
	
	use Symfony\Component\Form\AbstractType;
	use Symfony\Component\Form\FormBuilderInterface;
	
	use Symfony\Component\Form\Extension\Core\Type\TextType;
	use Symfony\Component\Form\Extension\Core\Type\TextareaType;
	use Symfony\Component\Form\Extension\Core\Type\SubmitType;
	use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
	use Symfony\Component\Form\Extension\Core\Type\EmailType;
	use Symfony\Component\Form\Extension\Core\Type\CollectionType;

	use Symfony\Component\OptionsResolver\OptionsResolver;

	use AppBundle\Form\AuthorType;

	class WorkType extends AbstractType {

		public function buildForm(FormBuilderInterface $builder, array $options) {

			$builder
				->add('title', TextType::class)
				->add('author', TextType::class)
				->add('secondaryAuthors', CollectionType::class, array(
					'allow_add' => true,
					'by_reference' => false,
					'entry_type' => AuthorType::class
					)
				)
				->add('summary',TextareaType::class)
				->add('theme',ChoiceType::class, array(
					'choices' => array(
						'BPM' => 'BPM',
						'Cloud Computing' => 'Cloud Computing',
						'Web Services' => 'Web Services',
						'SOA' => 'SOA'
					),
					'choices_as_values' => true
				))
				->add('email', EmailType::class)
				->add('gmail', EmailType::class)
				->add('presentationType',ChoiceType::class, array(
					'choices' => array(
						'Conferencia' => 'Conferencia',
						'Poster' => 'Poster'
					),
					'choices_as_values' => true
				))
				->add('enviar', SubmitType::class);
				
		}

		public function configureOptions(OptionsResolver $resolver) {
			$resolver->setDefaults(array('data_class' => 'AppBundle\Entity\Work','cascade_validation' => true));
		}
	}