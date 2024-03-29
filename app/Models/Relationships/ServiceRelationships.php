<?php

namespace App\Models\Relationships;

use App\Models\File;
use App\Models\Location;
use App\Models\Offering;
use App\Models\Organisation;
use App\Models\Referral;
use App\Models\ServiceEligibility;
use App\Models\ServiceGalleryItem;
use App\Models\ServiceLocation;
use App\Models\ServiceRefreshToken;
use App\Models\ServiceTaxonomy;
use App\Models\SocialMedia;
use App\Models\Tag;
use App\Models\Taxonomy;
use App\Models\UsefulInfo;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait ServiceRelationships
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function logoFile()
    {
        return $this->belongsTo(File::class, 'logo_file_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serviceLocations()
    {
        return $this->hasMany(ServiceLocation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function locations()
    {
        return $this->belongsToMany(Location::class, (new ServiceLocation())->getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function socialMedias()
    {
        return $this->morphMany(SocialMedia::class, 'sociable');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function usefulInfos()
    {
        return $this->hasMany(UsefulInfo::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function offerings()
    {
        return $this->hasMany(Offering::class)->orderBy('order', 'asc');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serviceTaxonomies()
    {
        return $this->hasMany(ServiceTaxonomy::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, (new ServiceTaxonomy())->getTable());
    }

    public function serviceEligibilities()
    {
        return $this->hasMany(ServiceEligibility::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function eligibilities(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, (new ServiceEligibility())->getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, table(UserRole::class));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serviceGalleryItems()
    {
        return $this->hasMany(ServiceGalleryItem::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serviceRefreshTokens(): HasMany
    {
        return $this->hasMany(ServiceRefreshToken::class);
    }

    /**
     * The tags that belong to the service.
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
