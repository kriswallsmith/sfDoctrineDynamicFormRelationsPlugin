This plugin allows you to embed related forms in a Doctrine form class,
manipulate those embedded forms using JavaScript (i.e. add more, remove some,
etc), and will automatically update those relations when the form is
processed.

    class AuthorForm extends BaseAuthorForm
    {
      public function configure()
      {
        $this->embedDynamicRelation('books');
      }
    }

This form can now be rendered something like this, enhanced with JavaScript:

    <form action="#" method="post">
      <!-- ... -->
      <ul>
        <?php foreach ($form['books'] as $bookFields): ?>
          <li>
            <table><?php echo $bookFields ?></table>
            <a href="javascript:remove_book(this)">remove this book</a>
          </li>
        <?php endforeach ?>
      </ul>
      <a href="javascript:add_book()">add another book</a>
      <!-- ... -->
    </form>

Once your JavaScript is setup (which is up to you), your user can remove some
of the listed books and add a few more. When you submit the form the database
will be updated to reflect those changes.

Limitations
-----------

 * The primary key of the related model must be "id"
 * The embedded form class must include an "id" field

Roadmap
-------

 * Detect the related model's primary key
 * Obfuscate and add the primary key for embedded forms when needed
